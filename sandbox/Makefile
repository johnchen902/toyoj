CFLAGS += -std=gnu11 -Wall -Wextra -Wshadow -D_GNU_SOURCE
OBJ = sandbox.o child.o child-execute.o grandchild.o

sandbox: $(OBJ)

sandbox.o: child.h
child.o: child.h child-execute.h
child-execute.o: child-execute.h grandchild.h
grandchild.o: grandchild.h

.PHONY: clean
clean:
	$(RM) sandbox $(OBJ)
